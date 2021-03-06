<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Installment;
use Carbon\Carbon;
use Endroid\QrCode\QrCode;
use Illuminate\Http\Request;
use App\Exceptions\InvalidRequestException;
use Illuminate\Support\Facades\DB;
use App\Models\InstallmentItem;
use App\Models\Order;

class InstallmentsController extends Controller
{
    public function index(Request $request)
    {
        $installments = Installment::query()
            ->where('user_id', $request->user()->id)
            ->paginate(10);

        return view('installments.index', compact('installments'));
    }

    public function show(Installment $installment)
    {
        // 权限验证
        $this->authorize('own', $installment);
        // 取出档期那分期所有的还款计划，并按还款顺序排序
        $items = $installment->items()->orderBy('sequence')->get();

        return view('installments.show', [
            'installment' => $installment,
            'items' => $items,
            // 下一个未完成还款的还款计划
            'nextItem' => $items->where('paid_at', null)->first()
        ]);
    }

    public function payByAlipay(Installment $installment)
    {
        if ($installment->order->closed) {
            throw new InvalidRequestException('对应的商品订单已被关闭');
        }
        if ($installment->status === Installment::STATUS_FINISHED) {
            throw new InvalidRequestException('该分期订单已经还清');
        }
        // 获取当前分期付款最近的一个未支付的还款计划
        if (!$nextItem = $installment->items()->whereNull('paid_at')->orderBy('sequence')->first()) {
            // 如果没有未支付的还款，原则上不可能，因为如果分期已结清则在上一个判断就退出了
            throw new InvalidRequestException('该分期订单已结清');
        }

        return app('alipay')->web([
            // 支付订单使用分期流水号 + 还款计划号
            'out_trade_no' => $installment->no . '_' . $nextItem->sequence,
            'total_amount' => $nextItem->total,
            'subject' => '支付 Laravel Shop 的分期订单：'.$installment->no,
            // 这里的 notify_url 和 return_url 可以覆盖掉在 AppServiceProvider 设置的回调地址
            'notify_url' =>  route('installments.alipay.notify'),
            'return_url' => route('installments.alipay.return'),
        ]);
    }

    /**
     * 支付宝前端回调
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function alipayReturn()
    {
        try {
            app('alipay')->verify();
        } catch (\Exception $e) {
            return view('pages.error', ['msg' => '数据不正确']);
        }

        return view('pages.success', ['msg' => '付款成功']);
    }

    /**
     * 支付宝后端回调
     */
    public function alipayNotify()
    {
        // 校验支付宝回调参数是否正确
        $data = app('alipay')->verify();
        // 如果订单状态不是成功或者结束，则不走后续的逻辑
        if (!in_array($data->trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            return app('alipay')->success();
        }
        if ($this->paid($data->out_trade_no, 'alipay', $data->trade_no)) {
            return app('alipay')->success();
        }

        return 'fail';
    }

    /**
     * 微信支付
     *
     * @param Installment $installment
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws InvalidRequestException
     */
    public function payByWehchat(Installment $installment)
    {
        if ($installment->order->closed) {
            throw new InvalidRequestException('对应的商品订单已经被关闭');
        }
        if ($installment->status === Installment::STATUS_FINISHED) {
            throw new InvalidRequestException('该分期订单已经结清');
        }
        if (!$nextItem = $installment->items()->whereNull('paid_at')->orderBy('sequence')->first()) {
            throw new InvalidRequestException('该分期订单已经结清');
        }

        $wechatOrder = app('wechat_pay')->scan([
            'out_trade_no' => $installment->no . '_' . $nextItem->sequence,
            'total_fee' => $nextItem->total * 100,
            'body' => '支付 Laravel Shop 的分期订单：' . $installment->no,
            'notify_url'   => route('installments.wechat.notify'),
        ]);

        $qrcode = new QrCode($wechatOrder->code_url);

        // 将生成的二维码图片以字符串的形式输出，并带上相应的相应类型
        return response($qrcode->writeString(), 200, ['Content-type' => $qrcode->getContentType()]);
    }

    /**
     * 微信支付回调
     *
     * @return string
     */
    public function wechatNotify()
    {
        $data = app('wechat_pay')->verify();
        if ($this->paid($data->out_trade_no, 'wechat', $data->transaction_id)) {
            return app('wechat_pay')->success();
        }

        return 'fail';
    }

    protected function paid($tradeNo, $paymentMethod, $paymentNo)
    {
        list($no, $sequence) = explode('_', $tradeNo);
        if (!$installment = Installment::query()->where('no', $no)->first()) {
            return false;
        }
        if (!$item = $installment->items()->where('sequence', $sequence)->first()) {
            return false;
        }
        if ($item->paid_at) {
            return true;
        }

        DB::transaction(function () use ($paymentNo, $paymentMethod, $no, $installment, $item) {
            $item->update([
                'paid_at' => Carbon::now(),
                'payment_method' => $paymentMethod,
                'payment_no' => $paymentNo
            ]);
            if ($item->sequence === 0) {
                $installment->update(['status' => Installment::STATUS_REPAYING]);
                $installment->order->update([
                    'paid_at' => Carbon::now(),
                    'payment_method' => 'installment',
                    'payment_no' => $no
                ]);
                event(new OrderPaid($installment->order));
            }
            if ($item->sequence === $installment->count - 1) {
                $installment->update(['status' => Installment::STATUS_FINISHED]);
            }
        });

        return true;
    }

    public function wechatRefundNotify(Request $request)
    {
        // 给微信的失败相应
        $failXml = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[FAIL]]></return_msg></xml>';
        // 微信回调参数校验
        $data = app('wechat_pay')->verify(null, true);
        // 根据订单号拆解出对应商品退款单号及对应的还款计划号
        list($no, $sequence) = explode('_', $data['out_refund_no']);

        $item = InstallmentItem::query()->whereHas('installment', function ($query) use ($no) {
            $query->whereHas('order', function ($query) use ($no) {
                $query->where('refund_no', $no); // 根据顶顶那的退款流水号找到对应的还款计划
            });
        })->where('sequence', $sequence)
        ->first();

        // 没有找到对应的订单，原则上不可能发生
        if (!$item) {
            return $failXml;
        }

        // 如果退款成功
        if ($data['refund_status'] === 'SUCCESS') {
            // 将还款计划退款状态修改为退款成功
            $item->update([
                'refund_status' => InstallmentItem::REFUND_STATUS_SUCCESS
            ]);
            $item->installment->refreshRefundStatus();
        } else {
            $item->update(['refund_stauts' => REFUND_STATUS_FAILED]);
        }

        return app('wechat_pay')->success();
    }
}
