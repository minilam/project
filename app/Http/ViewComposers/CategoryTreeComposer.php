<?php

namespace App\Http\ViewComposers;

use App\Services\CategoryService;
use Illuminate\View\View;

class CategoryTreeComposer
{
    protected $categoryService;

    // 使用 la'ravel 的依赖注入，自动注入我们需要的 CategoryService类
    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    // 当渲染值定的模板时， Laravel 会调用 compose 方法
    public function compose(View $view) {
        $view->with('categoryTree', $this->categoryService->getCategoryTree());
    }
}