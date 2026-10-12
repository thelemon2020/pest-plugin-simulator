<native:column class="w-full h-full gap-4 p-4 bg-theme-background">
    <native:text>{{ $status }}</native:text>
    <native:button label="Delete everything" @press="confirm" />
</native:column>
