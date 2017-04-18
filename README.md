# Spot2Vereinsflieger
Gateway Spot to Vereinsflieger.de

This script connects to the Spot satellite messenger XML API and generates flight log entrys in vereinsflieger.de when a takeoff or landing is detected. After sucessful entry of a flight, a Pushover notification is sent. Messages from Spot are interpreted as follows:

- Message "OK": Landing
- Message "CUSTOM": Takeoff
