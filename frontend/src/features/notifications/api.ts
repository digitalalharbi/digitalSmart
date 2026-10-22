import { api } from '@/shared/api/client';
import type {
  NotificationCategoryValue,
  NotificationFeed,
  NotificationPreferences,
  NotificationPreferencesInput,
  NotificationVocabulary,
} from './types';

export async function fetchNotifications(
  unreadOnly = false,
  category: NotificationCategoryValue | null = null,
): Promise<NotificationFeed> {
  const { data } = await api.get<{ data: NotificationFeed }>('/notifications', {
    params: {
      ...(unreadOnly ? { unread: '1' } : {}),
      ...(category === null ? {} : { category }),
    },
  });

  return data.data;
}

export async function fetchNotificationVocabulary(): Promise<NotificationVocabulary> {
  const { data } = await api.get<{ data: NotificationVocabulary }>('/notifications/vocabulary');

  return data.data;
}

export async function markNotificationRead(notificationId: string): Promise<NotificationFeed> {
  /*
   * The whole feed comes back. Marking one read changes the counts above the list, and a client decrementing
   * them itself would be a second implementation of arithmetic that does not know which kinds count as waiting.
   */
  const { data } = await api.post<{ data: NotificationFeed }>(
    `/notifications/${notificationId}/read`,
  );

  return data.data;
}

/** Following the link is reading it too; the server records both. */
export async function markNotificationClicked(notificationId: string): Promise<NotificationFeed> {
  const { data } = await api.post<{ data: NotificationFeed }>(
    `/notifications/${notificationId}/clicked`,
  );

  return data.data;
}

export async function dismissNotification(notificationId: string): Promise<NotificationFeed> {
  const { data } = await api.post<{ data: NotificationFeed }>(
    `/notifications/${notificationId}/dismiss`,
  );

  return data.data;
}

export async function markAllNotificationsRead(): Promise<NotificationFeed> {
  const { data } = await api.post<{ data: NotificationFeed }>('/notifications/read-all');

  return data.data;
}

export async function fetchNotificationPreferences(): Promise<NotificationPreferences> {
  const { data } = await api.get<{ data: NotificationPreferences }>('/notifications/preferences');

  return data.data;
}

export async function saveNotificationPreferences(
  input: NotificationPreferencesInput,
): Promise<NotificationPreferences> {
  const { data } = await api.put<{ data: NotificationPreferences }>(
    '/notifications/preferences',
    input,
  );

  return data.data;
}
