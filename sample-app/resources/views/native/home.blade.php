<native:column class="w-full h-full bg-theme-background">
    <native:list class="flex-1">
        @foreach (['/form' => 'Form', '/controls' => 'Controls', '/tabs' => 'Tabs', '/orders' => 'Orders', '/items' => 'Items', '/sheet' => 'Sheet', '/alerts' => 'Alerts', '/slow' => 'Slow'] as $url => $label)
            <native:list-item native:key="home-{{ $label }}" headline="{{ $label }}" @press="go('{{ $url }}')" trailingIcon="chevron_right" />
        @endforeach
    </native:list>
</native:column>
