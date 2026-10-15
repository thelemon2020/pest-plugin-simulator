<native:column class="w-full h-full bg-theme-background">
    <native:tab-row native:model="section">
        <native:tab label="Overview" />
        <native:tab label="Activity" />
        <native:tab label="Members" />
    </native:tab-row>
    <native:column class="w-full p-4">
        <native:text>{{ ['Overview content', 'Activity content', 'Members content'][$section] }}</native:text>
    </native:column>
</native:column>
