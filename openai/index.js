import { writeFileSync } from 'fs';
import OpenAI from 'openai';

const client = new OpenAI({
  apiKey: 'sk-proj-*',
});

const response = await client.images.generate({
  // background: 'auto',
  model: 'gpt-image-1',
  // moderation: 'auto',
  // n: 1,
  // output_compression: 100,
  // output_format: 'webp',
  prompt: `
    Realistic professional banner or flyer or postcard or poster
      - Choose profession or service
      - Include profession or service related male or female or child
      - Include profession or service related equipment or product or tool
      - Include two or three profession or service related text blocks
        - Use only horizontal text
        - Use same font size and color inside text block
      - Include solid color without gardient
  `,
  quality: 'low',
  size: '1024x1536',
});

response.data.forEach((item, i) => writeFileSync(`image-${Date.now() + i}.png`, Buffer.from(item.b64_json, 'base64')));
