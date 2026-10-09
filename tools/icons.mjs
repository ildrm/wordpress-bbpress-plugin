import fs from 'node:fs';
import zlib from 'node:zlib';
const crc = (data) => { let value = 0xffffffff; for (const byte of data) { value ^= byte; for (let bit=0;bit<8;bit++) value = (value >>> 1) ^ (value & 1 ? 0xedb88320 : 0); } return (value ^ 0xffffffff) >>> 0; };
const chunk = (type, data) => { const tag=Buffer.from(type), size=Buffer.alloc(4), check=Buffer.alloc(4);size.writeUInt32BE(data.length);check.writeUInt32BE(crc(Buffer.concat([tag,data])));return Buffer.concat([size,tag,data,check]); };
fs.mkdirSync('assets/icons',{recursive:true});
for (const size of [192,512]) { const header=Buffer.alloc(13);header.writeUInt32BE(size);header.writeUInt32BE(size,4);header[8]=8;header[9]=2;const bytes=Buffer.alloc((size*3+1)*size);
for (let y=0;y<size;y++) for(let x=0;x<size;x++) { const u=x/size,v=y/size;const bubble=u>.2&&u<.8&&v>.23&&v<.68;const tail=u>.27&&u<.4&&v>=.68&&v<.81&&(u-.27)<(.81-v)*1.1;const line=bubble&&u>.3&&u<.7&&((v>.35&&v<.39)||(v>.49&&v<.53));const color=line?[29,35,39]:(bubble||tail?[255,255,255]:[29,35,39]);const offset=y*(size*3+1)+1+x*3;bytes.set(color,offset); }
fs.writeFileSync('assets/icons/community-'+size+'.png',Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',header),chunk('IDAT',zlib.deflateSync(bytes,{level:9})),chunk('IEND',Buffer.alloc(0))])); }
