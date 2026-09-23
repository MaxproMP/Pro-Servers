# Usamos Bookworm (Debian 12) que tiene los repositorios al día
FROM node:20-bookworm-slim

# Instalamos herramientas de compilación y el unzip
RUN apt-get update && apt-get install -y python3 make g++ unzip && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src/app

COPY package*.json ./
RUN npm install --legacy-peer-deps

COPY . .

EXPOSE 3000

# Ejecutamos Node directamente
CMD ["node", "server.js"]