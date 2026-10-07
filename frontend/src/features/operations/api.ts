import { api } from '@/shared/api/client';
import type {
  Task,
  TaskBoard,
  TaskPriorityValue,
  TaskStateValue,
  TaskSubjectTypeValue,
  TaskVocabulary,
} from './types';

export type BoardScope = {
  clientId?: string | null;
  projectId?: string | null;
  /** A user id, the literal `me`, or `unassigned`. `me` is resolved on the server. */
  assignee?: string | null;
  priority?: TaskPriorityValue | null;
};

export async function fetchTaskVocabulary(): Promise<TaskVocabulary> {
  const { data } = await api.get<{ data: TaskVocabulary }>('/tasks/vocabulary');

  return data.data;
}

export async function fetchTaskBoard(scope: BoardScope): Promise<TaskBoard> {
  const params: Record<string, string> = {};

  if (scope.clientId) {
    params.client_id = scope.clientId;
  }

  if (scope.projectId) {
    params.project_id = scope.projectId;
  }

  if (scope.assignee) {
    params.assignee_id = scope.assignee;
  }

  if (scope.priority) {
    params.priority = scope.priority;
  }

  const { data } = await api.get<{ data: TaskBoard }>('/tasks/board', { params });

  return data.data;
}

export async function fetchTask(taskId: string): Promise<Task> {
  const { data } = await api.get<{ data: Task }>(`/tasks/${taskId}`);

  return data.data;
}

export async function openTask(input: {
  title: string;
  priority: TaskPriorityValue;
  description?: string;
  clientId?: string | null;
  projectId?: string | null;
  assigneeId?: string | null;
  dueOn?: string | null;
  subjectType?: TaskSubjectTypeValue | null;
  subjectId?: string | null;
}): Promise<Task> {
  const { data } = await api.post<{ data: Task }>('/tasks', {
    title: input.title,
    priority: input.priority,
    ...(input.description === undefined ? {} : { description: input.description }),
    ...(input.clientId ? { client_id: input.clientId } : {}),
    ...(input.projectId ? { project_id: input.projectId } : {}),
    ...(input.assigneeId ? { assignee_id: input.assigneeId } : {}),
    ...(input.dueOn ? { due_on: input.dueOn } : {}),
    ...(input.subjectType ? { subject_type: input.subjectType } : {}),
    ...(input.subjectId ? { subject_id: input.subjectId } : {}),
  });

  return data.data;
}

export async function moveTask(input: {
  taskId: string;
  state: TaskStateValue;
  reason?: string;
}): Promise<Task> {
  const { data } = await api.post<{ data: Task }>(`/tasks/${input.taskId}/state`, {
    state: input.state,
    ...(input.reason === undefined ? {} : { reason: input.reason }),
  });

  return data.data;
}

export async function assignTask(input: {
  taskId: string;
  assigneeId: string | null;
}): Promise<Task> {
  /*
   * An absent assignee unassigns, which is a deliberate outcome rather than a missing field. A board that
   * could only ever add an owner would accumulate work assigned to whoever touched it first.
   */
  const { data } = await api.post<{ data: Task }>(
    `/tasks/${input.taskId}/assignee`,
    input.assigneeId === null ? {} : { assignee_id: input.assigneeId },
  );

  return data.data;
}

export async function updateTask(input: {
  taskId: string;
  title?: string;
  description?: string | null;
  priority?: TaskPriorityValue;
  /** An empty string clears the date. Omitting the key leaves it alone. */
  dueOn?: string;
}): Promise<Task> {
  const { data } = await api.patch<{ data: Task }>(`/tasks/${input.taskId}`, {
    ...(input.title === undefined ? {} : { title: input.title }),
    ...(input.description === undefined ? {} : { description: input.description ?? '' }),
    ...(input.priority === undefined ? {} : { priority: input.priority }),
    ...(input.dueOn === undefined ? {} : { due_on: input.dueOn }),
  });

  return data.data;
}
