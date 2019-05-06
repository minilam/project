<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Installment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Exceptions\InvalidRequestException;
use Illuminate\Support\Facades\DB;

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
        list($no, $sequence) = explode('_', $data->trade_no);
        // 根据还款计划编号查询对应的还款计划，原则上不会找不到，这里的判断只是增强代码健壮性
        if (!$installment = Installment::query()->where('no', $no)->first()) {
            return 'fail';
        }
        if (!$item = $installment->items()->where('sequence', $sequence)->first()) {
            return 'fail';
        }
        if ($item->paid_at) {
            return app('alipay')->success();
        }

        DB::transaction(function () use ($data, $no, $installment, $item) {
            // 更新对应的还款计划
            $item->update([
                'paid_at' => Carbon::now(),
                'payment_method' => 'alipay',
                'payment_no' => $data->trade_no // 支付宝订单号
            ]);
            // 如果是第一笔还款
            if ($item->sequence === 0) {
                // 将分期付款的状态改为还款中
                $installment->update(['status' => Installment::STATUS_REPAYING]);
                // 将分期付款的商品状态修改为已付款
                $installment->order->update([
                    'paid_at' => now(),
                    'payment_method' => 'installment',
                    'payment_no' => $no
                ]);
                event(new OrderPaid($installment->order));
            }
            // 如果是最后一次还款
            if ($item->sequence === $installment->count - 1) {
                // 将分期付款的状态修改为已完成
                $installment->update(['status' => Installment::STATUS_FINISHED]);
            }
        });

        return app('alipay')->success();
    }
}
