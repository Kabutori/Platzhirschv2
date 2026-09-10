import {test,expect} from '@playwright/test';
import {mkdir} from 'node:fs/promises';
test('admin saves SMTP without exposing passwords and reaches requested areas',async({page})=>{
  let saved={enabled:true,host:'smtp.example.test',port:587,security:'starttls',username:'mailer',from_address:'mail@example.test',from_name:'Platzhirsch',password_set:true};
  let payload;
  await page.route('**/api/**',async route=>{
    const r=route.request(),p=new URL(r.url()).pathname;let body={};
    if(p.endsWith('/auth/me'))body={id:1,name:'Admin',role:'system_admin',permissions:['*'],installed_modules:['support','provisioning']};
    else if(p.endsWith('/modules'))body=['support','provisioning'].map(code=>({code,installed:true,version:'0.1.0',permissions:[],dependencies:{}}));
    else if(p.endsWith('/dashboard'))body={recent_audit:[]};
    else if(p.endsWith('/csrf'))body={token:'csrf'};
    else if(p.endsWith('/mail-settings/test'))body={message:'TLS-Verbindung geprüft.'};
    else if(p.endsWith('/mail-settings')){if(r.method()==='PUT'){payload=r.postDataJSON();saved={...saved,...payload};delete saved.password;}body=saved;}
    else if(p.endsWith('/database-servers'))body={servers:[],notice:'Prüfziele'};
    await route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
  });
  await page.goto('/administration/login');
  await expect(page.getByRole('button',{name:'Module',exact:true})).toBeVisible();
  await expect(page.getByRole('button',{name:'Support',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Serververwaltung',exact:true}).click();
  await expect(page.getByRole('button',{name:'Server hinzufügen'})).toBeVisible();
  await page.getByRole('button',{name:'System-Einstellungen',exact:true}).click();
  await page.getByRole('button',{name:'E-Mail / SMTP',exact:true}).click();
  await expect(page.getByLabel('SMTP-Passwort',{exact:true})).toHaveValue('');
  await page.getByLabel('SMTP-Server',{exact:true}).fill('smtp.neu.example.test');
  await expect(page.getByRole('button',{name:'Gespeicherte Verbindung prüfen'})).toBeDisabled();
  await page.getByRole('button',{name:'Speichern',exact:true}).click();
  await expect(page.getByRole('status')).toContainText('SMTP-Einstellungen gespeichert');
  expect(payload.password).toBe('');
  await page.getByRole('button',{name:'Gespeicherte Verbindung prüfen'}).click();
  await expect(page.getByRole('status')).toContainText('TLS-Verbindung geprüft.');
  await mkdir('test-results',{recursive:true});
  await page.screenshot({path:'test-results/design-smtp-desktop.png',fullPage:true});
});
