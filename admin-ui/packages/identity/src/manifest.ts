import { ShieldCheck } from 'lucide-react';
import type { ModuleUiManifest } from '@platzhirsch/ui-runtime/types';
export const identityManifest = {
  code: 'identity',
  nav: [
    {
      key: 'roles',
      label: 'Rollen & Rechte',
      scope: 'administration',
      permission: 'platform.roles.manage',
      order: 50,
      icon: ShieldCheck,
      screen: () => import('./Roles'),
    },
  ],
} satisfies ModuleUiManifest;
