@props([
    'title',
    'description',
])

<div class="flex w-full flex-col">
    <h1 class="text-3xl font-extrabold tracking-tight text-ink">{{ $title }}</h1>
    <p class="mt-1 text-sm text-muted">{{ $description }}</p>
</div>
