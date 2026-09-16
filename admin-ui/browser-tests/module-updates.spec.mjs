import {test,expect} from '@playwright/test';
test('module composition requires compatibility and explicit approval before build',async({page})=>{
 let posted;const repo='platzhirsch-module-reporting';
 await page.route('**/api/**',async route=>{
  const r=route.request(),p=new URL(r.url()).pathname;let body={};
  if(p.endsWith('/auth/me'))body={id:1,name:'Admin',role:'system_admin',permissions:['*'],installed_modules:['identity']};
  else if(p.endsWith('/csrf'))body={token:'csrf'};
  else if(p.endsWith('/dashboard'))body={recent_audit:[]};
  else if(p.endsWith('/modules'))body=[];
  else if(p.endsWith('/system-operations'))body={available:true,backups:[],packages:[],jobs:[]};
  else if(p.endsWith('/module-updates'))body={repositories:{[repo]:{installed:'0.1.0',versions:{'0.1.0':{commit:'a'.repeat(40),packages:[]},'0.2.0':{commit:'b'.repeat(40),packages:[]}}}},github_configured:true,pipeline_configured:true,reader_configured:true,registry_url:'https://example.test/api/module-registry',builds:[]};
  else if(p.endsWith('/preview'))body=r.postDataJSON().selection[repo]==='0.1.0'?{compatible:true,errors:[]}:{compatible:false,errors:['Reporting benötigt eine andere Contracts-Version.']};
  else if(p.endsWith('/builds')){posted=r.postDataJSON();body={status:'queued'}};
  await route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
 });
 await page.goto('/administration/login');await page.getByRole('button',{name:'Module',exact:true}).click();await page.getByRole('button',{name:'Versionen & Updates',exact:true}).click();
 const build=page.getByRole('button',{name:'Geprüften Build starten'});await expect(build).toBeDisabled();
 await page.getByLabel(`Zielversion ${repo}`).selectOption('0.2.0');await page.getByRole('button',{name:'Zusammenstellung prüfen'}).click();await expect(page.getByText('Diese Zusammenstellung ist nicht kompatibel.')).toBeVisible();await expect(build).toBeDisabled();
 await page.getByLabel(`Zielversion ${repo}`).selectOption('0.1.0');await page.getByRole('button',{name:'Zusammenstellung prüfen'}).click();await build.click();await expect(page.getByRole('button',{name:'Bestätigen',exact:true})).toBeDisabled();
 await page.getByLabel('Administrator-Passwort').fill('test-password');await page.getByLabel('Ich möchte diese Aktion ausführen.').check();await page.getByRole('button',{name:'Bestätigen',exact:true}).click();await expect(page.getByText('Build gestartet. Der Status kann unten aktualisiert werden.')).toBeVisible();expect(posted.selection[repo]).toBe('0.1.0');expect(posted.confirmation).toBe(true);
 await page.setViewportSize({width:390,height:844});await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);await page.screenshot({path:'test-results/design-module-updates-mobile.png',fullPage:true});await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:'test-results/design-module-updates-desktop.png',fullPage:true});
});
