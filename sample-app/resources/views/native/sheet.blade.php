<native:column class="w-full h-full gap-4 p-4 bg-theme-background">
    <native:text>{{ $status }}</native:text>
    <native:button label="Open sheet" @press="show" />
    @if ($open)
        <native:bottom-sheet visible="true" detents="medium" @dismiss="close">
            <native:column class="gap-3 p-4">
                <native:text>Pick something</native:text>
                <native:button label="Choose" @press="choose" />
            </native:column>
        </native:bottom-sheet>
    @endif
</native:column>
