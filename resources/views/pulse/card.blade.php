{{--
    Mobile push Pulse card. Publish with `php artisan vendor:publish --tag=mobile-push-views` to customise it.
    Everything shown comes from low-cardinality Pulse aggregates: no device, user, campaign or message identifiers.
--}}
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Mobile push"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.signal-slash />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if (count($connections) === 0 && count($reasons) === 0 && count($openCircuits) === 0 && count($throttled) === 0)
            <x-pulse::no-results />
        @else
            <div class="flex flex-col gap-6">
                @if (count($connections) > 0)
                    <x-pulse::table>
                        <colgroup>
                            <col width="100%" />
                            @foreach ($columns as $column)
                                <col width="0%" />
                            @endforeach
                            <col width="0%" />
                            <col width="0%" />
                        </colgroup>
                        <x-pulse::thead>
                            <tr>
                                <x-pulse::th>Connection</x-pulse::th>
                                @foreach ($columns as $column)
                                    <x-pulse::th class="text-right">{{ ucfirst($column) }}</x-pulse::th>
                                @endforeach
                                <x-pulse::th class="text-right whitespace-nowrap" title="accepted / (accepted + rejected + failed + unknown)">Accept rate</x-pulse::th>
                                <x-pulse::th class="text-right whitespace-nowrap" title="batch duration, average / max">Avg / max ms</x-pulse::th>
                            </tr>
                        </x-pulse::thead>
                        <tbody>
                            @foreach ($connections as $row)
                                <tr wire:key="{{ $row['label'] }}-spacer" class="h-2 first:h-0"></tr>
                                <tr wire:key="{{ $row['label'] }}-row">
                                    <x-pulse::td class="max-w-[1px]">
                                        <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</code>
                                    </x-pulse::td>
                                    @foreach ($columns as $column)
                                        <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                            @if ($sampleRate < 1)
                                                <span title="Sample rate: {{ $sampleRate }}, raw value: {{ number_format($row[$column]) }}">~{{ number_format($row[$column] * $scale) }}</span>
                                            @else
                                                {{ number_format($row[$column]) }}
                                            @endif
                                        </x-pulse::td>
                                    @endforeach
                                    <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                        {{ $row['rate'] === null ? '-' : $row['rate'].'%' }}
                                    </x-pulse::td>
                                    <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold whitespace-nowrap">
                                        {{ $row['avg_ms'] === null ? '-' : number_format($row['avg_ms']).' / '.number_format((int) $row['max_ms']) }}
                                    </x-pulse::td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-pulse::table>
                @endif

                <div class="grid grid-cols-1 @lg:grid-cols-3 gap-6">
                    <div>
                        <h3 class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400 mb-2">Top reason codes</h3>
                        @forelse ($reasons as $reason)
                            <div class="flex justify-between text-sm text-gray-700 dark:text-gray-300">
                                <code class="text-xs truncate">{{ $reason['reason'] }}</code>
                                <span class="font-bold tabular-nums">{{ number_format($reason['count']) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400 dark:text-gray-600">None</p>
                        @endforelse
                    </div>
                    <div>
                        <h3 class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400 mb-2">Open circuits</h3>
                        @forelse ($openCircuits as $circuit)
                            <p class="text-sm text-red-600 dark:text-red-400"><code class="text-xs">{{ $circuit }}</code></p>
                        @empty
                            <p class="text-sm text-gray-400 dark:text-gray-600">None</p>
                        @endforelse
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">{{ number_format($circuitTransitions) }} transitions in period</p>
                    </div>
                    <div>
                        <h3 class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400 mb-2">Throttled providers</h3>
                        @forelse ($throttled as $provider)
                            <p class="text-sm text-amber-600 dark:text-amber-400"><code class="text-xs">{{ $provider }}</code></p>
                        @empty
                            <p class="text-sm text-gray-400 dark:text-gray-600">None</p>
                        @endforelse
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">{{ number_format($throttleEvents) }} throttle events in period</p>
                    </div>
                </div>
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
