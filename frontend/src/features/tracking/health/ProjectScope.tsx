import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useProjectsQuery } from '@/features/projects';
import { EntityPicker } from '@/shared/ui/entity-picker';

/** Choose the project a tracking view is about. Searched, never listed: the 101st project is not a typo. */
export function ProjectScope({
  value,
  onChange,
}: {
  value: { id: string; label: string } | null;
  onChange: (value: { id: string; label: string } | null) => void;
}) {
  const { t } = useTranslation();
  const [term, setTerm] = useState('');
  const projects = useProjectsQuery({ search: term, per_page: 8 });

  return (
    <EntityPicker
      label={t('tracking.links.builder.project')}
      clearLabel={t('actions.clear')}
      value={value}
      onChange={(option) =>
        onChange(option === null ? null : { id: option.id, label: option.label })
      }
      onSearch={setTerm}
      options={(projects.data?.data ?? []).map((p) => ({
        id: p.id,
        label: p.name,
        context: p.client.name,
      }))}
      isLoading={projects.isFetching}
    />
  );
}
