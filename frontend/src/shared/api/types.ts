/** Envelope shapes the API uses for every paginated collection. */
export type PageMeta = {
  total: number;
  page: number;
  per_page: number;
  last_page: number;
  has_more: boolean;
};

export type Paginated<T> = {
  data: T[];
  meta: PageMeta;
};
