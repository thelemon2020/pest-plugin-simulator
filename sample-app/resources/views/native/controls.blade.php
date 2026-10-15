<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-4 p-4">
        <native:row class="w-full items-center justify-between">
            <native:text>Notifications</native:text>
            <native:toggle value="{{ $notifications ? '1' : '0' }}" @change="toggleNotifications" a11y-label="Notifications" />
        </native:row>
        <native:text>Notifications are {{ $notifications ? 'on' : 'off' }}</native:text>

        <native:checkbox value="{{ $subscribe ? '1' : '' }}" @change="toggleSubscribe" label="Subscribe" />
        <native:text>Subscribed: {{ $subscribe ? 'yes' : 'no' }}</native:text>

        <native:slider :min="0" :max="100" :step="1" native:model="volume" a11y-label="Volume" />
        <native:text>Volume: {{ $volume }}</native:text>

        <native:select value="{{ $size }}" :options="['Small', 'Medium', 'Large']" @change="chooseSize" a11y-label="Size" />
        <native:text>Size: {{ $size }}</native:text>

        <native:radio-group native:model="plan" label="Plan">
            <native:radio value="free" label="Free" />
            <native:radio value="pro" label="Pro" />
            <native:radio value="team" label="Team" />
        </native:radio-group>
        <native:text>Plan: {{ $plan }}</native:text>
    </native:column>
</native:scroll-view>
