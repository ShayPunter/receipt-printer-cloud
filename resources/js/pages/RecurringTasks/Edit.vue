<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';

interface RecurringTask {
    id: number;
    title: string;
    description: string | null;
    priority: 'low' | 'medium' | 'high';
    frequency_type: 'daily' | 'weekly' | 'monthly' | 'custom';
    frequency_value: number;
    frequency_unit: 'minutes' | 'hours' | 'days' | 'weeks' | 'months';
    day_of_week: string | null;
    day_of_month: number | null;
    time_of_day: string | null;
    is_active: boolean;
}

interface Props {
    recurringTask: RecurringTask;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Recurring Tasks',
        href: '/recurring-tasks',
    },
    {
        title: 'Edit',
        href: `/recurring-tasks/${props.recurringTask.id}/edit`,
    },
];

const form = useForm({
    title: props.recurringTask.title,
    description: props.recurringTask.description || '',
    priority: props.recurringTask.priority,
    frequency_type: props.recurringTask.frequency_type,
    frequency_value: props.recurringTask.frequency_value,
    frequency_unit: props.recurringTask.frequency_unit,
    day_of_week: props.recurringTask.day_of_week,
    day_of_month: props.recurringTask.day_of_month,
    time_of_day: props.recurringTask.time_of_day || '',
    is_active: props.recurringTask.is_active,
});

const submit = () => {
    form.patch(`/recurring-tasks/${props.recurringTask.id}`);
};
</script>

<template>
    <Head title="Edit Recurring Task" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <HeadingSmall
                title="Edit Recurring Task"
                description="Update your automated recurring action item"
            />

            <form @submit.prevent="submit" class="max-w-2xl space-y-6">
                <!-- Title -->
                <div class="grid gap-2">
                    <Label for="title">Task Title</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        required
                        placeholder="e.g., Weekly team standup"
                        :class="{ 'border-destructive': form.errors.title }"
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <!-- Description -->
                <div class="grid gap-2">
                    <Label for="description">Description (optional)</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="3"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                        placeholder="Additional details about this task..."
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <!-- Priority -->
                <div class="grid gap-2">
                    <Label for="priority">Priority</Label>
                    <select
                        id="priority"
                        v-model="form.priority"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                    >
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                    <InputError :message="form.errors.priority" />
                </div>

                <!-- Frequency Type -->
                <div class="grid gap-2">
                    <Label for="frequency_type">Frequency</Label>
                    <select
                        id="frequency_type"
                        v-model="form.frequency_type"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                    >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="custom">Custom</option>
                    </select>
                    <InputError :message="form.errors.frequency_type" />
                </div>

                <!-- Weekly: Day of Week -->
                <div v-if="form.frequency_type === 'weekly'" class="grid gap-2">
                    <Label for="day_of_week">Day of Week</Label>
                    <select
                        id="day_of_week"
                        v-model="form.day_of_week"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                    >
                        <option value="monday">Monday</option>
                        <option value="tuesday">Tuesday</option>
                        <option value="wednesday">Wednesday</option>
                        <option value="thursday">Thursday</option>
                        <option value="friday">Friday</option>
                        <option value="saturday">Saturday</option>
                        <option value="sunday">Sunday</option>
                    </select>
                    <InputError :message="form.errors.day_of_week" />
                </div>

                <!-- Monthly: Day of Month -->
                <div
                    v-if="form.frequency_type === 'monthly'"
                    class="grid gap-2"
                >
                    <Label for="day_of_month">Day of Month</Label>
                    <Input
                        id="day_of_month"
                        v-model.number="form.day_of_month"
                        type="number"
                        min="1"
                        max="31"
                        placeholder="1-31"
                    />
                    <InputError :message="form.errors.day_of_month" />
                </div>

                <!-- Custom Frequency -->
                <div v-if="form.frequency_type === 'custom'" class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="frequency_value">Every</Label>
                        <Input
                            id="frequency_value"
                            v-model.number="form.frequency_value"
                            type="number"
                            min="1"
                            required
                        />
                        <InputError :message="form.errors.frequency_value" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="frequency_unit">Unit</Label>
                        <select
                            id="frequency_unit"
                            v-model="form.frequency_unit"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                        >
                            <option value="minutes">Minutes</option>
                            <option value="hours">Hours</option>
                            <option value="days">Days</option>
                            <option value="weeks">Weeks</option>
                            <option value="months">Months</option>
                        </select>
                        <InputError :message="form.errors.frequency_unit" />
                    </div>
                </div>

                <!-- Time of Day -->
                <div class="grid gap-2">
                    <Label for="time_of_day"
                        >Time of Day (optional, 24-hour format)</Label
                    >
                    <Input
                        id="time_of_day"
                        v-model="form.time_of_day"
                        type="time"
                        placeholder="HH:MM"
                    />
                    <InputError :message="form.errors.time_of_day" />
                    <p class="text-xs text-muted-foreground">
                        Leave empty to create task immediately when due
                    </p>
                </div>

                <!-- Active Status -->
                <div class="flex items-center space-x-2">
                    <Checkbox
                        id="is_active"
                        :checked="form.is_active"
                        @update:checked="(val) => (form.is_active = val as boolean)"
                    />
                    <Label
                        for="is_active"
                        class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                        Active (task will run automatically)
                    </Label>
                </div>

                <!-- Submit -->
                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        Update Recurring Task
                    </Button>

                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-if="form.recentlySuccessful"
                            class="text-sm text-neutral-600"
                        >
                            Updated successfully!
                        </p>
                    </Transition>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
