import {test,expect} from '@playwright/test';
import {mkdir} from 'node:fs/promises';
for (const mobile of [false,true]) test(`weather configuration and forecast states ${mobile?'mobile':'desktop'}`,async({page})=>{
 if(mobile) await page.setViewportSize({width:390,height:844});
 let settings=null,forecastStatus='unconfigured';
 await page.route('**/api/**',async route=>{
  const r=route.request(),p=new URL(r.url()).pathname;let body=[];
  if(p.endsWith('/auth/me'))body={id:1,name:'Restaurant',role:'restaurant_admin',tenant_id:1,permissions:['restaurant.configure'],enabled_modules:['weather']};
  else if(p.endsWith('/csrf'))body={token:'csrf'};
  else if(p.endsWith('/weather/settings')){
   if(r.method()==='PUT'){settings={...r.postDataJSON(),version:1};forecastStatus='fresh';body={saved:true};}
   else body={settings,commercial_key_configured:false};
  } else if(p.endsWith('/weather'))body={status:forecastStatus,timezone:'Europe/Berlin',fetched_at:'2026-09-10T08:00:00Z',days:settings?Array.from({length:7},(_,i)=>({date:`2026-09-${10+i}`,code:i===1?61:0,temperature:21+i,rain_probability:i===1?80:10,warning:i===1})):[]};
  else if(p.endsWith('/rooms'))body=[{id:1,name:'Terrasse',weather_dependent:1,color:'sage',outdoor:true}];
  await route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
 });
 await page.goto('/restaurant/login');
 await page.getByRole('button',{name:'Räume',exact:true}).click();
 await expect(page.getByText('Wettervorhersage ist noch nicht eingerichtet oder ausgeschaltet.')).toBeVisible();
 await page.getByRole('button',{name:'Wetter einstellen'}).click();
 const d=page.getByRole('dialog',{name:'Wetter-Einstellungen'});
 await d.getByLabel('Wetter automatisch aktualisieren').check();
 await d.getByLabel('Breitengrad',{exact:true}).fill('52.52');
 await d.getByLabel('Längengrad',{exact:true}).fill('13.405');
 await d.getByLabel('Anbieternutzung').selectOption('evaluation');
 await mkdir('test-results',{recursive:true});
 await page.screenshot({path:`test-results/design-weather-settings-${mobile?'mobile':'desktop'}.png`});
 await d.getByRole('button',{name:'Speichern',exact:true}).click();
 await expect(page.getByText('80 % Regen')).toBeVisible();
 await expect(page.getByText('Raumnutzung prüfen',{exact:true})).toBeVisible();
 await page.getByRole('region',{name:'Wettervorhersage'}).scrollIntoViewIfNeeded();
 await page.screenshot({path:`test-results/design-weather-forecast-${mobile?'mobile':'desktop'}.png`});
 forecastStatus='stale';await page.reload();
 await page.getByRole('button',{name:'Räume',exact:true}).click();
 await expect(page.getByText(/Veraltete Vorhersage/)).toBeVisible();
});
