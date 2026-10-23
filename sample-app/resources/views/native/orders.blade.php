<native:column class="w-full h-full gap-3 p-4 bg-theme-background">
    @foreach ($paid as $order => $isPaid)
        <native:column native:key="order-{{ $order }}" a11y-label="Order {{ $order }}" class="w-full gap-2 p-3 rounded-lg bg-theme-surface">
            <native:text>Order {{ $order }}</native:text>
            <native:text>{{ $isPaid ? 'Paid' : 'Unpaid' }}</native:text>
            <native:button label="Pay" @press="pay({{ $order }})" />
            <native:button label="Ship" @press="ship({{ $order }})" />
        </native:column>
    @endforeach
</native:column>
