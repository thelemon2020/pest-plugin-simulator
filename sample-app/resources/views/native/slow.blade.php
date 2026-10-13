<native:column class="w-full h-full gap-4 p-4 bg-theme-background">
    <native:outlined-text-input label="Echo" native:model="echo" autocapitalize="none" />
    <native:text>Echoed: {{ $echo }}</native:text>
    <native:button label="Start work" @press="work" />
    <native:text>{{ $result }}</native:text>
</native:column>
