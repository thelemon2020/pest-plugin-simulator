<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-4 p-4">
        <native:outlined-text-input label="Name" native:model="name" />
        <native:outlined-text-input label="Email" native:model="email" keyboard="email" />
        <native:outlined-text-input label="Password" native:model="password" secure />
        <native:outlined-text-input placeholder="Search…" @submit="search" leading-icon="search" />
        <native:outlined-text-input label="Notes" native:model="notes" multiline :min-lines="3" />
        <native:text>Hello, {{ $name === '' ? 'nobody' : $name }}</native:text>
        <native:button label="Save" @press="save" />
        @if ($status !== '')
            <native:text>{{ $status }}</native:text>
        @endif
    </native:column>
</native:scroll-view>
