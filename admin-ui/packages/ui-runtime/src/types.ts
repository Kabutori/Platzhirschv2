import type { ComponentType } from 'react';
import type { LucideIcon } from 'lucide-react';
export interface ModuleNavigation {
  key: string;
  label: string;
  scope: 'administration' | 'restaurant';
  permission: string;
  order: number;
  icon: LucideIcon;
  screen: () => Promise<{ default: ComponentType }>;
}
export interface ModuleUiManifest {
  code: string;
  nav: ModuleNavigation[];
}
