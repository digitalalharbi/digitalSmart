import { api } from '@/shared/api/client';
import type { NotificationFeed, NotificationVocabulary } from './types';

export async function fetchNotifications(unreadOnly = false): Promise<NotificationFeed> {
  const { data } = await api.get<{ data: NotificationFeed }>('/notifications', {
    params: unreadOnly ? { unread: '1' } : {},
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

export async function markAllNotificationsRead(): Promise<NotificationFeed> {
  const { data } = await api.post<{ data: NotificationFeed }>('/notifications/read-all');

  return data.data;
}
