{{--
    Monthly spending: a column per month (one hue, the selected month in the stronger step),
    and the selected month split by category as ranked bars. Server-rendered: no chart library.

    - Single series, so no legend: the heading and the view toggle say what's plotted.
    - Values: the selected month is labelled on its cap; any other month shows its value on
      hover or keyboard focus. Every figure is also in the table view, so nothing is hover-only.
    - Months without bills draw no bar (a zero draws nothing) but stay on the axis.

    Props: $spending (App\Reports\MonthlySpending), $selected ('Y-m'), $currency.
--}}
@props(['spending', 'selected', 'currency'])

@php
    use App\Enums\ExpenseCategory;
    use App\Support\Money;
    use Illuminate\Support\Number;

    $ticks = $spending->axisTicks();
    $top = end($ticks);
    $current = $spending->month($selected);
    $pounds = fn (int $pence) => Number::currency(intdiv($pence, 100), in: $currency, locale: 'en_GB', precision: 0);
@endphp

<div {{ $attributes->class('space-y-6') }}>
    {{-- Columns --}}
    <div class="flex gap-3 pt-5" data-test="spending-columns">
        {{-- Y axis: clean ticks, recessive --}}
        <div class="relative h-44 w-12 shrink-0 text-end text-xs text-muted tabular-nums" aria-hidden="true">
            @foreach ($ticks as $tick)
                <span class="absolute right-0 -translate-y-1/2" style="bottom: {{ $tick / $top * 100 }}%">{{ $pounds($tick) }}</span>
            @endforeach
        </div>

        <div class="relative h-44 flex-1">
            {{-- Gridlines: 1px hairlines, the baseline one step stronger --}}
            @foreach ($ticks as $tick)
                <div @class(['absolute inset-x-0 h-px', 'bg-muted/50' => $tick === 0, 'bg-line' => $tick !== 0]) style="bottom: {{ $tick / $top * 100 }}%" aria-hidden="true"></div>
            @endforeach

            <div class="absolute inset-0 flex items-end justify-around" role="group" aria-label="{{ __('Spending per month') }}">
                @foreach ($spending->months as $month)
                    @php($isSelected = $month['key'] === $current['key'])
                    @php($height = $top > 0 ? $month['total'] / $top * 100 : 0)
                    {{-- The whole column slot is the hit target, not just the painted bar --}}
                    <button
                        type="button"
                        wire:key="month-{{ $month['key'] }}"
                        wire:click="selectSpendingMonth('{{ $month['key'] }}')"
                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                        aria-label="{{ $month['start']->translatedFormat('F Y') }}: {{ Money::format($month['total'], $currency) }}"
                        class="group relative flex h-full flex-1 cursor-pointer items-end justify-center rounded-(--radius-control) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-chart"
                        data-test="spending-month"
                    >
                        @if ($month['total'] > 0)
                            {{-- 24px column, 4px rounded data end, square on the baseline --}}
                            <span
                                @class([
                                    'w-6 rounded-t-[4px] transition-colors',
                                    'bg-chart' => $isSelected,
                                    'bg-chart-soft group-hover:bg-chart/70 group-focus-visible:bg-chart/70' => ! $isSelected,
                                ])
                                style="height: {{ $height }}%"
                            ></span>
                        @endif

                        {{-- Value on the cap: always for the selected month, on hover/focus for the rest --}}
                        <span
                            @class([
                                'pointer-events-none absolute left-1/2 -translate-x-1/2 whitespace-nowrap text-xs font-bold text-ink',
                                'hidden group-hover:block group-focus-visible:block' => ! $isSelected,
                            ])
                            style="bottom: calc({{ $height }}% + 4px)"
                            aria-hidden="true"
                        >{{ Money::format($month['total'], $currency) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- X axis --}}
    <div class="-mt-4 flex gap-3 text-xs text-muted" aria-hidden="true">
        <div class="w-12 shrink-0"></div>
        <div class="flex flex-1 justify-around">
            @foreach ($spending->months as $month)
                <span @class(['flex-1 text-center', 'font-bold text-ink' => $month['key'] === $current['key']])>{{ $month['start']->translatedFormat('M') }}</span>
            @endforeach
        </div>
    </div>

    {{-- The selected month by category: ranked bars, one hue --}}
    <div data-test="spending-categories">
        <div class="flex items-baseline justify-between gap-3">
            <p class="text-sm font-bold text-ink">{{ $current['start']->translatedFormat('F Y') }}</p>
            <p class="text-sm font-bold text-ink">{{ Money::format($current['total'], $currency) }}</p>
        </div>

        @if ($current['categories'] === [])
            <p class="mt-2 text-sm text-muted">{{ __('No bills this month.') }}</p>
        @else
            <ul class="mt-3 space-y-3" role="list">
                @foreach ($current['categories'] as $value => $pence)
                    @php($category = ExpenseCategory::from($value))
                    @php($share = intdiv($pence * 100, $current['total']))
                    <li class="grid grid-cols-[7.5rem_1fr_auto] items-center gap-3 text-sm">
                        <span class="flex items-center gap-1.5 text-muted">
                            <flux:icon :icon="$category->icon()" variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ $category->label() }}
                        </span>
                        <span class="h-2 rounded-r-[4px] bg-chart" style="width: {{ max(1, $pence / $current['total'] * 100) }}%" aria-hidden="true"></span>
                        <span class="text-end tabular-nums">
                            <span class="font-bold text-ink">{{ Money::format($pence, $currency) }}</span>
                            <span class="text-muted">· {{ $share }}%</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Table view: every figure without hovering or relying on bar lengths --}}
    <details class="text-sm">
        <summary class="cursor-pointer font-semibold text-muted hover:text-ink">{{ __('Show as a table') }}</summary>
        <table class="mt-3 w-full" data-test="spending-table">
            <thead>
                <tr class="text-xs text-muted">
                    <th scope="col" class="py-1 text-start font-medium">{{ __('Month') }}</th>
                    <th scope="col" class="py-1 text-end font-medium">{{ __('Spent') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach (array_reverse($spending->months) as $month)
                    <tr>
                        <th scope="row" class="py-1.5 text-start font-normal text-ink">{{ $month['start']->translatedFormat('F Y') }}</th>
                        <td class="py-1.5 text-end tabular-nums text-ink">{{ Money::format($month['total'], $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</div>
