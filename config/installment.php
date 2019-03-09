<?php

return [
    // 分期费率， key 为分期数， value为费率
    'installment_fee_rate' => [
        3 => 1.5,
        6 => 2,
        12 => 2.5 
    ], 
    'min_installment_amount' => 300, // 最低分期金额
    'installment_fine_rate' => 0.05 // 预期日利息费率 0.05%
];