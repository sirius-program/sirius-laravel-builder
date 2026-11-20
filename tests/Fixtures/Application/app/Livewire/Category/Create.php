<?php

declare(strict_types=1);

namespace App\Livewire\Category;

use App\Actions\Category\CreateCategory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Create extends Component
{
    public string $name = '';

    public function save(CreateCategory $action): void
    {
        $action->handle(['name' => $this->name]);
        $this->reset('name');
    }

    public function render(): View
    {
        return view('livewire.category.create');
    }
}
