<form wire:submit="save">
    <label for="category-name">Name</label>
    <input id="category-name" wire:model="name">
    @error('name') <span>{{ $message }}</span> @enderror
    <button type="submit">Save</button>
</form>
