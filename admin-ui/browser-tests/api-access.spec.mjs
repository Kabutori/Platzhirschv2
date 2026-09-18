import { test, expect } from '@playwright/test';
test('API management creates a scoped token once and submits exact portal approval', async ({ page }) => {
  let issued, approved;
  const state={scopes:['support:read','support:write'],tokens:[],modules:['support','mcp'],settings:[],events:[],confirmations:[{id:'approval-1',operation:'support.post.support',preview:JSON.stringify({body:{subject:'Prüfanfrage'}}),expires_at:'2026-09-18',approved_at:null}]};
  await page.route('**/api/**',async route=>{
    const r=route.request(),p=new URL(r.url()).pathname;let body={};
    if(p.endsWith('/auth/me'))body={id:1,name:'Admin',role:'system_admin',permissions:['*'],installed_modules:['api','mcp','support']};
    else if(p.endsWith('/modules'))body=['api','mcp','support'].map(code=>({code,installed:true,version:'0.1.2',permissions:[],dependencies:{}}));
    else if(p.endsWith('/dashboard'))body={recent_audit:[]};
    else if(p.endsWith('/csrf'))body={token:'csrf'};
    else if(p.endsWith('/access/tokens')){issued=r.postDataJSON();body={token:'ph_test-once'};}
    else if(p.endsWith('/confirmations/approval-1/approve')){approved=r.postDataJSON();state.confirmations[0].approved_at='now';body={status:'approved'};}
    else if(p.endsWith('/access'))body=state;
    await route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
  });
  await page.goto('/administration/login');
  await page.getByRole('button',{name:'System-Einstellungen',exact:true}).click();
  await page.getByRole('button',{name:'API & MCP',exact:true}).click();
  const create=page.locator('form').filter({has:page.getByRole('heading',{name:'Neuen Zugang erstellen'})});
  await expect(create.getByRole('button',{name:'Token erstellen'})).toBeDisabled();
  await create.getByLabel('Name',{exact:true}).fill('Integration');
  await create.getByLabel('support:read',{exact:true}).check();
  await create.getByLabel('Aktuelles Kennwort').fill('Test-password-123');
  await create.getByLabel('Aktion verbindlich bestätigen').check();
  await create.getByRole('button',{name:'Token erstellen'}).click();
  await expect(page.getByLabel('Neuer API-Token')).toHaveValue('ph_test-once');
  expect(issued.scopes).toEqual(['support:read']);
  await page.getByRole('button',{name:'Gespeichert, ausblenden'}).click();
  await expect(page.getByLabel('Neuer API-Token')).toHaveCount(0);
  const approval=page.locator('article').filter({hasText:'support.post.support'});
  await approval.getByLabel('Aktuelles Kennwort').fill('Test-password-123');
  await approval.getByLabel('Aktion verbindlich bestätigen').check();
  await approval.getByRole('button',{name:'Diese Aktion freigeben'}).click();
  await expect(approval).toContainText('Freigegeben, wartet auf Ausführung.');
  expect(approved.confirmed).toBe(true);
});
