import { Package } from 'lucide-react';
import type { ModuleUiManifest } from '@platzhirsch/ui-runtime/types';
export const billingManifest = {
  code: 'billing',
  nav: [
    {
      key: 'module-shop',
      label: 'Modul-Shop',
      scope: 'restaurant',
      permission: 'modules.manage',
      order: 70,
      icon: Package,
      screen: () => import('./Shop'),
    },
  ],
} satisfies ModuleUiManifest;
