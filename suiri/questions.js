'use strict';
// suiri.html / suiri3.html / online/suiri-online.html の3ファイルで共通の
// カード・質問リスト定義。ルール変更はこのファイルだけを直せば3ファイルに反映される。
// （imgSrc・cardHTML など、フォルダ位置に依存する表示部分は各HTML側に残している）

const MOTIFS=[
  {id:1,n:'アヒル',icon:'🦆'},
  {id:2,n:'クマ',icon:'🐻'},
  {id:3,n:'リス',icon:'🐿️'},
  {id:4,n:'ウサギ',icon:'🐰'}
];
const SEASONS=[
  {id:1,n:'はる',icon:'🌸',color:'#df6b94'},
  {id:2,n:'なつ',icon:'🌊',color:'#33a796'},
  {id:3,n:'あき',icon:'🍁',color:'#df843f'},
  {id:4,n:'ふゆ',icon:'⛄',color:'#5484ba'}
];
const CARDS=[];
for(const motif of MOTIFS)for(const season of SEASONS){
  const index=(motif.id-1)*4+(season.id-1);
  CARDS.push({index,motif,season});
}
const bit=i=>(1<<i)>>>0;
const maskOf=arr=>arr.reduce((m,i)=>(m|bit(i))>>>0,0);
const has=(mask,i)=>!!((mask>>>i)&1);
function popcount(x){x>>>=0;x=x-((x>>>1)&0x55555555);x=(x&0x33333333)+((x>>>2)&0x33333333);return (((x+(x>>>4))&0x0F0F0F0F)*0x01010101)>>>24;}
const cardName=i=>`${CARDS[i].season.n}の${CARDS[i].motif.n}`;

const TYPE_MASKS=MOTIFS.map(m=>maskOf(CARDS.filter(c=>c.motif.id===m.id).map(c=>c.index)));
const SEASON_MASKS=SEASONS.map(s=>maskOf(CARDS.filter(c=>c.season.id===s.id).map(c=>c.index)));
const cnt=(hand,mask)=>popcount((hand&mask)>>>0);
const animalKinds=hand=>TYPE_MASKS.filter(m=>cnt(hand,m)>0).length;
const seasonKinds=hand=>SEASON_MASKS.filter(m=>cnt(hand,m)>0).length;

function makeQuestions(){
  const qs=[];
  const yesNo=v=>v?'そうだよ！':'ちがうよ';
  const variant=(id,label,evalFn,fmt=yesNo)=>({id,label,eval:evalFn,fmt});
  // no＝カード番号。通常カードはすべて表（easy）のみの片面。
  const add=(no,side,key,label,category,variants,reciprocal=false)=>qs.push({no,side,key,label,category,variants,reciprocal});
  const pairs=[];
  for(let a=0;a<4;a++)for(let b=a+1;b<4;b++)pairs.push([a,b]);
  const ordered=[];
  for(let a=0;a<4;a++)for(let b=0;b<4;b++)if(a!==b)ordered.push([a,b]);
  // 4種の合計は手札の枚数で固定なので「AとB」と「残り2種」は必ず同じ答えになる。重複を避けて3組だけ使う。
  const oddPairs=[[0,1],[0,2],[0,3]];
  const mi=i=>MOTIFS[i].icon, mn=i=>MOTIFS[i].n, si=i=>SEASONS[i].icon, sn=i=>SEASONS[i].n;

  // ── 1・2：ひとつの動物／季節を見る ──
  add(1,'easy','animal-any','（動物）はいる？','動物',MOTIFS.map((x,i)=>variant(`a${i}`,`${mi(i)} ${mn(i)}はいる？`,hand=>cnt(hand,TYPE_MASKS[i])>0?1:0)));
  add(2,'easy','season-any','（季節）はある？','季節',SEASONS.map((x,i)=>variant(`s${i}`,`${si(i)} ${sn(i)}はある？`,hand=>cnt(hand,SEASON_MASKS[i])>0?1:0)));

  // ── 3：具体的な1枚 ──
  add(3,'easy','card-present','具体的な1枚はある？','1まい',Array.from({length:16},(_,c)=>{
    const mIdx=Math.floor(c/4), sIdx=c%4;
    return variant(`c${c}`,`${si(sIdx)}${sn(sIdx)}の${mi(mIdx)}${mn(mIdx)}はある？`,hand=>has(hand,c)?1:0);
  }));

  // ── 4・5：4種類すべているか ──
  add(4,'easy','animal-all','4種類の動物がすべている？','動物',[variant('all','🐾 4種類の動物がすべているの？',hand=>animalKinds(hand)===4?1:0)]);
  add(5,'easy','season-all','4種類の季節がすべてある？','季節',[variant('all','🎨 4種類の季節がすべてあるの？',hand=>seasonKinds(hand)===4?1:0)]);

  // ── 6・7：ふたつを同じ数字でくらべる ──
  add(6,'easy','animal-same','（動物A）と（動物B）は同じ数？','くらべる',pairs.map(([a,b])=>variant(`a${a}-${b}`,`${mi(a)}${mn(a)}と ${mi(b)}${mn(b)}は 同じ数？`,hand=>cnt(hand,TYPE_MASKS[a])===cnt(hand,TYPE_MASKS[b])?1:0)));
  add(7,'easy','season-same','（季節A）と（季節B）は同じ枚数？','くらべる',pairs.map(([a,b])=>variant(`s${a}-${b}`,`${si(a)}${sn(a)}と ${si(b)}${sn(b)}は 同じ枚数？`,hand=>cnt(hand,SEASON_MASKS[a])===cnt(hand,SEASON_MASKS[b])?1:0)));

  // ── 8・9：一番多い ──
  add(8,'easy','animal-most','（動物）が一番多い？','動物',MOTIFS.map((x,i)=>variant(`a${i}`,`${mi(i)} ${mn(i)}が一番多い？（同率もOK）`,hand=>{const c=TYPE_MASKS.map(m=>cnt(hand,m));return c[i]===Math.max(...c)?1:0;})));
  add(9,'easy','season-most','（季節）が一番多い？','季節',SEASONS.map((x,i)=>variant(`s${i}`,`${si(i)} ${sn(i)}が一番多い？（同率もOK）`,hand=>{const c=SEASON_MASKS.map(m=>cnt(hand,m));return c[i]===Math.max(...c)?1:0;})));

  // ── 10・11：どちらが多い ──
  add(10,'easy','animal-more','（動物A）は（動物B）より多い？','くらべる',ordered.map(([a,b])=>variant(`a${a}-${b}`,`${mi(a)}${mn(a)}は ${mi(b)}${mn(b)}より多い？`,hand=>cnt(hand,TYPE_MASKS[a])>cnt(hand,TYPE_MASKS[b])?1:0)));
  add(11,'easy','season-more','（季節A）は（季節B）より多い？','くらべる',ordered.map(([a,b])=>variant(`s${a}-${b}`,`${si(a)}${sn(a)}は ${si(b)}${sn(b)}より多い？`,hand=>cnt(hand,SEASON_MASKS[a])>cnt(hand,SEASON_MASKS[b])?1:0)));

  // ── 12・13：ふたつ合わせて奇数 ──
  add(12,'easy','animal-odd','（動物A）＋（動物B）は奇数匹？','くらべる',oddPairs.map(([a,b])=>variant(`a${a}-${b}`,`${mi(a)}${mn(a)}と ${mi(b)}${mn(b)}は あわせて奇数匹？`,hand=>cnt(hand,(TYPE_MASKS[a]|TYPE_MASKS[b])>>>0)%2)));
  add(13,'easy','season-odd','（季節A）＋（季節B）は奇数枚？','くらべる',oddPairs.map(([a,b])=>variant(`s${a}-${b}`,`${si(a)}${sn(a)}と ${si(b)}${sn(b)}は あわせて奇数枚？`,hand=>cnt(hand,(SEASON_MASKS[a]|SEASON_MASKS[b])>>>0)%2)));

  // ── 14・15：ちょうど1つ ──
  add(14,'easy','animal-one','（動物）はちょうど1匹？','動物',MOTIFS.map((x,i)=>variant(`a${i}`,`${mi(i)} ${mn(i)}はちょうど1匹？`,hand=>cnt(hand,TYPE_MASKS[i])===1?1:0)));
  add(15,'easy','season-one','（季節）はちょうど1枚？','季節',SEASONS.map((x,i)=>variant(`s${i}`,`${si(i)} ${sn(i)}はちょうど1枚？`,hand=>cnt(hand,SEASON_MASKS[i])===1?1:0)));

  // ── 16：自分の手札を1まい見せて、共通の要素を数えてもらう ──
  // variantIdの数字＝見せるカード番号(0..15)。見せられるのは実際に自分の手札にある1まいだけ
  // （どの1まいを見せられるかはHTML側で、その手番の本人の手札にしぼりこむ）。
  add(16,'easy','card-common','自分の1まいを見せて、共通の要素は何まい？','ぴったり',Array.from({length:16},(_,c)=>{
    const mIdx=Math.floor(c/4), sIdx=c%4;
    const mask=(TYPE_MASKS[mIdx]|SEASON_MASKS[sIdx])>>>0;
    return variant(`c${c}`,`${si(sIdx)}${sn(sIdx)}の${mi(mIdx)}${mn(mIdx)}を見せるね。同じ動物か同じ季節のカードは何まい？`,hand=>cnt(hand,mask),v=>`${v}まい`);
  }));

  // ── ⚡ 相互回答（通常デッキとは別枠。先手の最初の1回だけ）──
  // 101/102のみ初手で提示される（各HTML側のINITIAL_QUESTIONS/suiri_initial_cardsで絞り込み）。
  // 103/104は定義だけ残していて、現在のゲームでは使われない。
  add(101,'strong','strong-animal-count','⚡（動物）は何匹？','つよい',MOTIFS.map((x,i)=>variant(`a${i}`,`${mi(i)} ${mn(i)}は何匹？`,hand=>cnt(hand,TYPE_MASKS[i]),v=>`${v}匹`)),true);
  add(102,'strong','strong-season-count','⚡（季節）は何枚？','つよい',SEASONS.map((x,i)=>variant(`s${i}`,`${si(i)} ${sn(i)}は何枚？`,hand=>cnt(hand,SEASON_MASKS[i]),v=>`${v}枚`)),true);
  add(103,'strong','strong-animal-kinds','⚡動物は何種類いる？','つよい',[variant('kinds','⚡🐾 動物は何種類いる？',animalKinds,v=>`${v}種類`)],true);
  add(104,'strong','strong-season-kinds','⚡季節は何種類ある？','つよい',[variant('kinds','⚡🎨 季節は何種類ある？',seasonKinds,v=>`${v}種類`)],true);
  return qs;
}
const QUESTIONS=makeQuestions();
const QUESTION_BY_KEY=Object.fromEntries(QUESTIONS.map(q=>[q.key,q]));
