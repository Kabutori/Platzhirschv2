import { ChartNoAxesCombined } from 'lucide-react';
import type { ModuleUiManifest } from '@platzhirsch/ui-runtime/types';
export const reportingManifest = {
  code: 'reporting',
  nav: [
    {
      key: 'reporting',
      label: 'Erweiterte Auswertungen',
      scope: 'restaurant',
      permission: 'reporting.read',
      order: 20,
      icon: ChartNoAxesCombined,
      screen: () => import('./Reports'),
    },
  ],
} satisfies ModuleUiManifest;
