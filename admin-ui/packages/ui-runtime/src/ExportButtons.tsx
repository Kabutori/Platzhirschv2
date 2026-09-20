import { useEffect, useState } from 'react';
import { api, portal } from './api';
import { usePreferences } from './preferences';
export async function downloadFile(path: string, filename: string, tenant?: string) {
  const response = await fetch('/api/' + path, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/octet-stream, application/json',
      'X-Platzhirsch-Portal': portal,
      ...(tenant ? { 'X-Tenant-ID': tenant } : {}),
    },
  });
  if (!response.ok || response.redirected) {
    const error = await response.json().catch(() => null);
    throw new Error(error?.message || 'Export fehlgeschlagen. Bitte Sitzung und Berechtigung prüfen.');
  }
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}
export function ExportButtons({
  path,
  filename,
  tenant,
  disabled = false,
  background = false,
}: {
  path: string;
  filename: string;
  tenant?: string;
  disabled?: boolean;
  background?: boolean;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState('');
  const storageKey = 'platzhirsch-export:' + path + ':' + (tenant || '');
  const [job, setJob] = useState<any>(() => {
    try {
      const id = background ? sessionStorage.getItem(storageKey) : null;
      return id ? { id, status: 'queued' } : null;
    } catch {
      return null;
    }
  });
  useEffect(() => {
    try {
      if (job?.id) sessionStorage.setItem(storageKey, job.id);
      else sessionStorage.removeItem(storageKey);
    } catch {}
  }, [job?.id, storageKey]);
  useEffect(() => {
    if (!job || !['queued', 'running'].includes(job.status)) return;
    let cancelled = false;
    const timer = setInterval(() => {
      api(path + '/' + job.id, 'GET', undefined, tenant)
        .then((result) => {
          if (!cancelled) setJob(result);
        })
        .catch((e) => {
          if (!cancelled) {
            setError(e.message);
            setJob(null);
          }
        });
    }, 3000);
    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [job?.id, job?.status, path, tenant]);
  const { value: preferences } = usePreferences();
  if (!preferences.exportEnabled) return null;
  return (
    <div className="toolbar" aria-label="Übersicht exportieren">
      {(
        [
          ['csv', preferences.csvEnabled],
          ['xlsx', preferences.xlsxEnabled],
          ['pdf', preferences.pdfEnabled],
        ] as const
      )
        .filter(([, enabled]) => enabled)
        .map(([format]) => (
          <button
            key={format}
            disabled={disabled || busy}
            onClick={async () => {
              setBusy(true);
              setError('');
              try {
                await downloadFile(
                  path + (path.includes('?') ? '&' : '?') + 'format=' + format,
                  filename + '.' + format,
                  tenant,
                );
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            {format === 'pdf' ? 'PDF herunterladen' : format.toUpperCase() + ' exportieren'}
          </button>
        ))}
      {background && <span>Exportiert die gesamte für Sie sichtbare Übersicht.</span>}
      {background && preferences.csvEnabled && (
        <button
          disabled={disabled || busy || ['queued', 'running'].includes(job?.status)}
          onClick={async () => {
            setBusy(true);
            setError('');
            try {
              setJob(await api(path, 'POST', { format: 'csv' }, tenant));
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          Großen CSV-Export im Hintergrund erstellen
        </button>
      )}
      {job && (
        <span role="status">
          {
            (
              {
                queued: 'Export wartet auf den Hintergrunddienst …',
                running: 'Export wird erstellt …',
                failed: 'Export fehlgeschlagen. Berechtigungen und Hintergrunddienst prüfen.',
                completed: `${job.rows ?? 0} Datensätze bereit – Download 24 Stunden verfügbar.`,
              } as Record<string, string>
            )[job.status]
          }
        </span>
      )}
      {job?.status === 'completed' && (
        <button
          disabled={busy}
          onClick={async () => {
            setBusy(true);
            setError('');
            try {
              await downloadFile(path + '/' + job.id + '/download', filename + '.csv', tenant);
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          Fertigen Export herunterladen
        </button>
      )}
      {busy && <span role="status">Export wird erstellt …</span>}
      {error && <p role="alert">{error}</p>}
    </div>
  );
}
