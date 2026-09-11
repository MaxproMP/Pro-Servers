FROM node:20-bullseye

WORKDIR /usr/src/app

COPY package*.json ./
RUN npm install --omit=dev

COPY . .

# Exponemos el puerto 3001 para que Node le mande el webhook al bot
EXPOSE 3001

CMD ["node", "index.js"]