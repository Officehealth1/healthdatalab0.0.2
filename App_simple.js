import React from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { StatusBar } from 'expo-status-bar';
import { StyleSheet, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';

// Import screens
import DashboardScreen from './src/screens/DashboardScreen';
import HealthFormScreen from './src/screens/HealthFormScreen';
import LongevityFormScreen from './src/screens/LongevityFormScreen';
import TestScreen from './src/screens/TestScreen';

// Initialize database and services
import { initDatabase } from './src/database/database';
import SyncService from './src/services/SyncService';
import NotificationService from './src/services/NotificationService';

const Tab = createBottomTabNavigator();

export default function App() {
  React.useEffect(() => {
    const initializeApp = async () => {
      try {
        // Initialize database synchronously first
        initDatabase();
        
        // Then initialize services
        await NotificationService.initialize().catch(e => console.warn('Notification service failed:', e));
        await SyncService.initialize().catch(e => console.warn('Sync service failed:', e));
        
        console.log('App initialized successfully');
      } catch (error) {
        console.error('Error initializing app:', error);
      }
    };

    initializeApp();
  }, []);

  return (
    <View style={styles.container}>
      <StatusBar style="auto" />
      <NavigationContainer>
        <Tab.Navigator
          screenOptions={({ route }) => ({
            tabBarIcon: ({ focused, color, size }) => {
              let iconName;

              if (route.name === 'Dashboard') {
                iconName = focused ? 'analytics' : 'analytics-outline';
              } else if (route.name === 'Health Form') {
                iconName = focused ? 'medical' : 'medical-outline';
              } else if (route.name === 'Longevity Form') {
                iconName = focused ? 'fitness' : 'fitness-outline';
              } else if (route.name === 'Test') {
                iconName = focused ? 'bug' : 'bug-outline';
              }

              return <Ionicons name={iconName} size={size} color={color} />;
            },
            tabBarActiveTintColor: '#007AFF',
            tabBarInactiveTintColor: 'gray',
            headerStyle: {
              backgroundColor: '#007AFF',
            },
            headerTintColor: '#fff',
            headerTitleStyle: {
              fontWeight: 'bold',
            },
          })}
        >
          <Tab.Screen 
            name="Dashboard" 
            component={DashboardScreen} 
            options={{ title: 'Health Tracker' }}
          />
          <Tab.Screen 
            name="Health Form" 
            component={HealthFormScreen}
            options={{ title: 'Health Report' }}
          />
          <Tab.Screen 
            name="Longevity Form" 
            component={LongevityFormScreen}
            options={{ title: 'Longevity Assessment' }}
          />
          <Tab.Screen 
            name="Test" 
            component={TestScreen}
            options={{ title: 'Test Suite' }}
          />
        </Tab.Navigator>
      </NavigationContainer>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f5f5f7',
  },
});
