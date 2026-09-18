import { useState } from 'react';
import { portal } from './api';
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
}: {
  path: string;
  filename: string;
  tenant?: string;
  disabled?: boolean;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState('');
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
      {busy && <span role="status">Export wird erstellt …</span>}
      {error && <p role="alert">{error}</p>}
    </div>
  );
}
