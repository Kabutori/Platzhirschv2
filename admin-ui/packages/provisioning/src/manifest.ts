import { Database } from 'lucide-react';
import type { ModuleUiManifest } from '@platzhirsch/ui-runtime/types';
export const provisioningManifest = {
  code: 'provisioning',
  nav: [
    {
      key: 'database-servers',
      label: 'Datenbankserver',
      scope: 'administration',
      permission: 'provisioning.servers.read',
      order: 80,
      icon: Database,
      screen: () => import('./Servers'),
    },
  ],
} satisfies ModuleUiManifest;
