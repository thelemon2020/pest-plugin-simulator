<native:column class="w-full h-full gap-4 p-4 bg-theme-background">
    <native:text>{{ $rate }}</native:text>
    <native:text>{{ $arrives }}</native:text>
    <native:text>{{ $status }}</native:text>
    <native:button label="Pay" @press="pay" />
</native:column>
