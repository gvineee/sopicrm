# PLACEHOLDER image — see README.md and src/index.js. Node.js chosen only as a
# convenient Foundation-phase default; the Devices module agent may change the
# runtime entirely once the real Suprema G-SDK integration is implemented.
FROM node:22-alpine

WORKDIR /app

COPY package.json ./
COPY src ./src

EXPOSE 4000

CMD ["node", "src/index.js"]
