<native:column class="w-full h-full bg-theme-background">
    @if ($opened !== '')
        <native:text class="p-4">{{ $opened }}</native:text>
    @endif
    <native:list class="flex-1">
        @foreach (range(1, 40) as $item)
            <native:list-item native:key="item-{{ $item }}" headline="Item {{ $item }}" @press="open({{ $item }})" />
        @endforeach
    </native:list>
</native:column>
