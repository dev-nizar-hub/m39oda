/**
 * RestoOS Cloud — WebSerial ESC/POS Thermal Printer Driver (80mm / 58mm)
 * Compatible with Sunmi, Epson TM-T20/T88, Xprinter, Rongta and generic POS USB/Serial printers.
 * Uses Web Serial API (Chrome/Edge/Opera) with seamless automatic fallback to window.print().
 */

class ThermalPrinterDriver {
    constructor() {
        this.port = null;
        this.writer = null;
        this.baudRate = 9600; // Standard ESC/POS serial baudrate (or 115200 for high-speed USB)
        this.isConnected = false;
        this.paperWidthMm = 80; // 80mm default (48 chars/line) or 58mm (32 chars/line)
    }

    /**
     * Check if Web Serial API is supported in current browser
     */
    static isSupported() {
        return 'serial' in navigator;
    }

    /**
     * Request user permission and connect to USB/Serial POS printer
     */
    async connect() {
        if (!ThermalPrinterDriver.isSupported()) {
            console.warn('RestoOS: Web Serial API not supported in this browser. Falling back to browser print.');
            return false;
        }

        try {
            this.port = await navigator.serial.requestPort();
            await this.port.open({ baudRate: this.baudRate });
            this.writer = this.port.writable.getWriter();
            this.isConnected = true;
            console.log('RestoOS: Thermal Printer connected successfully.');
            return true;
        } catch (err) {
            console.warn('RestoOS: Could not connect to serial printer:', err.message);
            this.isConnected = false;
            return false;
        }
    }

    /**
     * Disconnect current serial port
     */
    async disconnect() {
        if (this.writer) {
            try {
                this.writer.releaseLock();
            } catch (e) {}
            this.writer = null;
        }
        if (this.port) {
            try {
                await this.port.close();
            } catch (e) {}
            this.port = null;
        }
        this.isConnected = false;
    }

    /**
     * Send raw ESC/POS byte buffer to connected printer
     */
    async writeRaw(bytes) {
        if (!this.writer) {
            throw new Error('Printer not connected');
        }
        await this.writer.write(new Uint8Array(bytes));
    }

    /**
     * Kick Cash Drawer (Open drawer)
     */
    async openCashDrawer() {
        if (!this.isConnected) return;
        // ESC p 0 25 250 (pulse 50ms to pin 2)
        const cmd = [0x1B, 0x70, 0x00, 0x19, 0xFA];
        await this.writeRaw(cmd);
    }

    /**
     * Build ESC/POS command array for an order receipt
     */
    buildOrderEscPos(data) {
        const encoder = new TextEncoder();
        const bytes = [];

        const append = (arr) => bytes.push(...arr);
        const appendText = (text) => append(encoder.encode(text));

        // 1. Initialize printer: ESC @
        append([0x1B, 0x40]);

        // 2. Center alignment: ESC a 1
        append([0x1B, 0x61, 0x01]);

        // 3. Double-height header: GS ! 0x10
        append([0x1D, 0x21, 0x11]);
        appendText((data.restaurant_nom || 'RESTO OS').toUpperCase() + '\n');

        // Normal size: GS ! 0x00
        append([0x1D, 0x21, 0x00]);
        if (data.restaurant_adresse) appendText(data.restaurant_adresse + '\n');
        if (data.restaurant_ville) appendText(data.restaurant_ville + ' - MAROC\n');
        if (data.restaurant_tel) appendText('Tel: ' + data.restaurant_tel + '\n');
        appendText('------------------------------------------------\n');

        // Order Number & Time
        append([0x1D, 0x21, 0x01]); // Emphasized
        appendText('COMMANDE #' + (data.numero_ticket || data.id_commande || '---') + '\n');
        append([0x1D, 0x21, 0x00]);
        appendText('Date: ' + (data.date_commande || new Date().toLocaleString()) + '\n');
        if (data.caissier) appendText('Caissier: ' + data.caissier + '\n');
        appendText('------------------------------------------------\n');

        // 4. Left alignment for items: ESC a 0
        append([0x1B, 0x61, 0x00]);

        // Items Header
        appendText('QTE  ARTICLE                           TOTAL\n');
        appendText('------------------------------------------------\n');

        const items = data.lignes || data.items || [];
        items.forEach(item => {
            const qty = String(item.quantite || 1).padEnd(4, ' ');
            const nom = String(item.nom_sandwich || item.nom || 'Article').substring(0, 30).padEnd(32, ' ');
            const total = (parseFloat(item.prix_unitaire || item.prix || 0) * parseInt(item.quantite || 1)).toFixed(2) + ' DH';
            const totalPad = total.padStart(12, ' ');
            appendText(qty + nom + totalPad + '\n');
            if (item.sauce || item.supplements) {
                const sup = '   + ' + (item.supplements || item.sauce);
                appendText(sup.substring(0, 48) + '\n');
            }
        });

        appendText('------------------------------------------------\n');

        // 5. Total Right Aligned: ESC a 2
        append([0x1B, 0x61, 0x02]);
        append([0x1D, 0x21, 0x11]); // Double size
        appendText('TOTAL: ' + parseFloat(data.total || data.montant_total || 0).toFixed(2) + ' DH\n');
        append([0x1D, 0x21, 0x00]);

        if (data.montant_recu && parseFloat(data.montant_recu) > 0) {
            appendText('Recu: ' + parseFloat(data.montant_recu).toFixed(2) + ' DH\n');
            const rendu = parseFloat(data.montant_recu) - parseFloat(data.total || data.montant_total || 0);
            appendText('Rendu: ' + Math.max(0, rendu).toFixed(2) + ' DH\n');
        }

        // 6. Footer Center: ESC a 1
        append([0x1B, 0x61, 0x01]);
        appendText('\nMerci pour votre visite !\nA bientot chez ' + (data.restaurant_nom || 'nous') + '\n');
        appendText('Powered by RestoOS Cloud (m39oda.ma)\n');

        // 7. Feed 4 lines & Full paper cut: GS V 65 3
        append([0x1B, 0x64, 0x04]);
        append([0x1D, 0x56, 0x41, 0x03]);

        return bytes;
    }

    /**
     * Print receipt directly to thermal printer or fallback to window.print()
     */
    async printReceipt(data) {
        if (this.isConnected && this.writer) {
            try {
                const bytes = this.buildOrderEscPos(data);
                await this.writeRaw(bytes);
                await this.openCashDrawer();
                return true;
            } catch (err) {
                console.warn('RestoOS: Serial print failed, using window.print() fallback:', err);
            }
        }

        // Fallback: Use browser printing
        window.print();
        return false;
    }
}

// Global Singleton
window.restoPrinter = new ThermalPrinterDriver();

