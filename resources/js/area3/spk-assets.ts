/** Vite can emit root /build URLs; retain the actual module's Apache subfolder prefix. */
export function spkLogoAssetUrl(assetUrl:string,moduleUrl:string){
  const asset=new URL(assetUrl,moduleUrl),module=new URL(moduleUrl);
  const build=module.pathname.lastIndexOf('/build/');
  if(asset.origin===module.origin&&asset.pathname.startsWith('/build/')&&build>0)asset.pathname=module.pathname.slice(0,build)+asset.pathname;
  return asset.href;
}
export const SPK_LOGO_URL=spkLogoAssetUrl(new URL('./assets/spk-telkom-akses.png',import.meta.url).href,import.meta.url);
