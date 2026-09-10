import {test,expect} from '@playwright/test';
test('operations require confirmation, submit a catalog ID and show safe results on mobile',async({page})=>{
 let posted;
 await page.route('**/api/**',async route=>{
 const p=new URL(route.request().url()).pathname;let body={};
 if(p.endsWith('/auth/me'))body={id:1,name:'Admin',role:'system_admin',permissions:['*'],installed_modules:['identity']};
 else if(p.endsWith('/csrf'))body={token:'csrf'};
 else if(p.endsWith('/dashboard'))body={recent_audit:[]};
 else if(p.endsWith('/modules'))body=[];
 else if(p.endsWith('/system-operations')){
 if(route.request().method()==='POST'){posted=route.request().postDataJSON();return route.fulfill({status:202,contentType:'application/json',body:JSON.stringify({id:posted.request_id,status:'queued'})})}
 body={available:true,backups:[{id:'backup-test',label:'Sicherung vom 10.09.2026'}],packages:[{id:'Platzhirsch-0.1.0-preview.103',label:'Windows Preview 102'}],jobs:posted?[{id:posted.request_id,action:'restore',status:'success',message:'Aktion erfolgreich abgeschlossen.',createdAt:'2026-09-10T12:00:00Z'}]:[]};
 }
 await route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
 });
 await page.goto('/administration/login');await page.getByRole('button',{name:'System',exact:true}).click();await page.getByRole('button',{name:'Backups & Updates',exact:true}).click();
 await page.getByRole('button',{name:'Wiederherstellen',exact:true}).click();
 await expect(page.getByRole('button',{name:'Verbindlich starten'})).toBeDisabled();
 await page.getByLabel('Administrator-Passwort').fill('test-password');await page.getByRole('checkbox').check();
 await page.setViewportSize({width:390,height:844});await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
 await page.screenshot({path:'test-results/design-operations-confirm-mobile.png',fullPage:true});
 await page.getByRole('button',{name:'Verbindlich starten'}).click();await expect(page.getByText('Aktion erfolgreich abgeschlossen.')).toBeVisible();
 expect(posted.target).toBe('backup-test');expect(posted.action).toBe('restore');expect(posted.confirmation).toBe(true);
 await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:'test-results/design-operations-desktop.png',fullPage:true});
});
