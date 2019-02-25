<?php

namespace App\Console\Commands\Cron;

use App\Jobs\RefundCrowdfundingOrders;
use App\Models\CrowdfundingProduct;
use App\Models\Order;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FinishCrowdfunding extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:finish-crowdfunding';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'End crowdfunding cron job';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        CrowdfundingProduct::query()->with(['product']) // 预加载商品数据
            ->where('end_at', '<=', Carbon::now())
            // 开团中
            ->where('status', CrowdfundingProduct::STATUS_FUNDING)
            ->get()
            ->each(function(CrowdfundingProduct $crowdfunding) {
                // 如果拼团的目标金额 大于实际的 金额
                if ($crowdfunding->target_amount > $crowdfunding->total_amount) {
                    // 调用失败的逻辑，进行退款
                } else {
                    // 成功的逻辑
                    $this->crowdfundingSucceed($crowdfunding);
                }
            });
    }

    // 开团成功
    protected function crowdfundingSucceed (CrowdfundingProduct $crowdfunding)
    {
        // 只需要将转台变为 拼团成功即可
        $crowdfunding->update(['status' => CrowdfundingProduct::STATUS_SUCCESS]);
    }

    // 开团失败
    protected function crowdfundingFailed(CrowdfundingProduct $crowdfunding)
    {
        // 1. 将拼团状态改为失败
        $crowdfunding->update(['status' => CrowdfundingProduct::STATUS_FAIL]);
        dispatch(new RefundCrowdfundingOrders($crowdfunding)); // 利用队列来执行耗时较长的请求
    }
}
