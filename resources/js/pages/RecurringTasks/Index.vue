<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

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
    next_run_at: string | null;
    last_run_at: string | null;
    frequency_description: string;
    created_at: string;
}

interface Props {
    recurringTasks: RecurringTask[];
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Recurring Tasks',
        href: '/recurring-tasks',
    },
];

const getPriorityVariant = (priority: string) => {
    switch (priority) {
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        case 'low':
            return 'secondary';
        default:
            return 'default';
    }
};

const toggleTask = (taskId: number) => {
    router.post(`/recurring-tasks/${taskId}/toggle`, {}, {
        preserveScroll: true,
    });
};

const deleteTask = (taskId: number) => {
    if (confirm('Are you sure you want to delete this recurring task?')) {
        router.delete(`/recurring-tasks/${taskId}`, {
            preserveScroll: true,
        });
    }
};

const formatDate = (dateString: string | null) => {
    if (!dateString) return 'Not scheduled';
    return new Date(dateString).toLocaleString();
};
</script>

<template>
    <Head title="Recurring Tasks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Recurring Tasks</h1>
                    <p class="text-sm text-muted-foreground">
                        Manage your automated recurring action items
                    </p>
                </div>
                <Link href="/recurring-tasks/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Add Recurring Task
                    </Button>
                </Link>
            </div>

            <div v-if="recurringTasks.length === 0" class="text-center py-12">
                <p class="text-muted-foreground mb-4">
                    No recurring tasks yet. Create your first one to get
                    started!
                </p>
                <Link href="/recurring-tasks/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Create Recurring Task
                    </Button>
                </Link>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="task in recurringTasks"
                    :key="task.id"
                    :class="{
                        'opacity-60': !task.is_active,
                    }"
                >
                    <CardHeader>
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <CardTitle class="text-lg">{{
                                    task.title
                                }}</CardTitle>
                                <CardDescription class="mt-1">
                                    {{ task.frequency_description }}
                                </CardDescription>
                            </div>
                            <Badge :variant="getPriorityVariant(task.priority)">
                                {{ task.priority }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p
                            v-if="task.description"
                            class="text-sm text-muted-foreground line-clamp-2"
                        >
                            {{ task.description }}
                        </p>

                        <div class="text-xs text-muted-foreground space-y-1">
                            <div>
                                <span class="font-medium">Next run:</span>
                                {{ formatDate(task.next_run_at) }}
                            </div>
                            <div v-if="task.last_run_at">
                                <span class="font-medium">Last run:</span>
                                {{ formatDate(task.last_run_at) }}
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="toggleTask(task.id)"
                            >
                                <Power
                                    v-if="!task.is_active"
                                    class="mr-2 h-4 w-4"
                                />
                                <PowerOff v-else class="mr-2 h-4 w-4" />
                                {{ task.is_active ? 'Deactivate' : 'Activate' }}
                            </Button>
                            <Link :href="`/recurring-tasks/${task.id}/edit`">
                                <Button variant="outline" size="sm">
                                    <Pencil class="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            </Link>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="deleteTask(task.id)"
                            >
                                <Trash2 class="h-4 w-4 text-destructive" />
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
