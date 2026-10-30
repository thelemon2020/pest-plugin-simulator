<native:column class="w-full h-full gap-4 p-4 bg-theme-background">
    <native:text>{{ $status }}</native:text>
    <native:button icon="add" @press="add" a11y-label="Add" />
    <native:outlined-text-input native:model="note" />
</native:column>
