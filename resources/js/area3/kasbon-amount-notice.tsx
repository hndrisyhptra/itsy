import { money } from './domain';
import { KASBON_SETTLEMENT_POLICY, type KasbonSettlement } from './kasbon-settlement';
import { Notice } from './ui';

/** Informational UI only. Read a frozen quote when issued; never reprice historical kasbons. */
export function KasbonAmountNotice({gross,amount,settlement,estimate=false}:{gross:number;amount:number;settlement?:KasbonSettlement;estimate?:boolean}){
  const withheld=settlement?.policy===KASBON_SETTLEMENT_POLICY;
  return <div className="kasbon-amount-explanation">
    <Notice>{withheld?'PPh 2,5% hanya dipotong dari Jasa Mandor. Material tidak dipotong. Nilai kasbon dan transfer memakai nilai setelah potongan PPh, sehingga dapat berbeda dari tagihan bruto yang diajukan.':'Kasbon historis ini mengikuti nominal tersimpan tanpa kebijakan potongan PPh 2,5%. Nilainya tidak dihitung ulang.'}{estimate&&' Nilai ini merupakan perkiraan sebelum kasbon diajukan.'}</Notice>
    <div className="summary-cards compact"><div><span>Nilai Pengajuan Bruto</span><strong>{money(withheld?settlement.gross:gross)}</strong></div>{withheld&&<div><span>Potongan PPh 2,5% · Jasa Mandor</span><strong>{money(settlement.withheld)}</strong></div>}<div><span>{withheld?'Nilai setelah potongan PPh':'Nilai Kasbon Tersimpan'}{estimate?' (perkiraan)':''}</span><strong>{money(amount)}</strong></div></div>
  </div>;
}
