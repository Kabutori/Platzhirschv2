import { MessageSquare } from 'lucide-react';
import type { ModuleUiManifest } from '@platzhirsch/ui-runtime/types';
export const supportManifest = {
  code: 'support',
  nav: [
    {
      key: 'support',
      label: 'Support',
      scope: 'restaurant',
      permission: 'support.access',
      order: 90,
      icon: MessageSquare,
      screen: () => import('./Support'),
    },
  ],
} satisfies ModuleUiManifest;
