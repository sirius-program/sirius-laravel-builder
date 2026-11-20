<?php

declare(strict_types=1);

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class CreateCategory
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): Category
    {
        Gate::authorize('create', Category::class);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:80']])->validate();

        $category = new Category;
        $category->name = $data['name'];
        $category->save();

        return $category;
    }
}
