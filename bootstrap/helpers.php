<?php

function route_class()
{
    return str_replace('.', '-', Route::currentRouteName());
}

if (!function_exists('big_number')) {
    // 扩展包： composer require moontoast/math
    function big_number($number, $scale = 2)
    {
        return new \Moontoast\Math\BigNumber($number, $scale);
    }
}
