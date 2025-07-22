import React, { useEffect, useState } from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { StatusBar } from 'expo-status-bar';
import { StyleSheet, View, Alert } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { initDatabase } from './src/database/database';
import AuthService from './src/services/AuthService';
import SyncService from './src/services/SyncService';
import SecureHealthSync from './src/services/SecureHealthSync';

// Import screens
import LoginScreen from './src/screens/LoginScreen';
import DashboardScreen from './src/screens/DashboardScreen';
import HealthFormScreen from './src/screens/HealthFormScreen';
import LongevityFormScreen from './src/screens/LongevityFormScreen';

const Tab = createBottomTabNavigator();

export default function App() {
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [userInfo, setUserInfo] = useState(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    // Initialize database and check for existing session
    const initApp = async () => {
      try {
        await initDatabase();
        console.log('App database initialization completed');
        
        // Initialize SyncService for real-time sync
        const syncService = new SyncService();
        await syncService.initialize();
        console.log('SyncService initialized successfully');
        
        // SECURITY: Initialize secure authentication
        const authState = await SecureHealthSync.loadAuthenticationState();
        if (authState.success) {
          console.log('SecureHealthSync: Found existing authentication');
          setUserInfo({
            email: authState.userEmail,
            emailHash: authState.userEmail, // Use email as hash for secure system
            hasExistingData: true,
            isSecureAuth: true
          });
          setIsLoggedIn(true);
        } else {
          // Fallback to old authentication system
          console.log('SecureHealthSync: No secure auth found, checking legacy system');
          const storedTokens = await AuthService.getStoredTokens();
          if (storedTokens && storedTokens.accessToken) {
            setUserInfo({
              email: AuthService.currentEmail || 'stored_user@example.com',
              emailHash: storedTokens.userHash,
              hasExistingData: true,
              isSecureAuth: false
            });
            setIsLoggedIn(true);
          }
        }
      } catch (error) {
        console.error('Failed to initialize database:', error);
        Alert.alert('Database Error', 'Failed to initialize app database. Some features may not work correctly.');
      } finally {
        setIsLoading(false);
      }
    };
    
    initApp();
  }, []);

  const handleLoginSuccess = (userData) => {
    setUserInfo(userData);
    setIsLoggedIn(true);
  };

  const handleLogout = async () => {
    try {
      // Use secure logout if available
      if (userInfo && userInfo.isSecureAuth) {
        await SecureHealthSync.logout();
      } else {
        await AuthService.clearTokens();
      }
      setUserInfo(null);
      setIsLoggedIn(false);
    } catch (error) {
      console.error('Logout error:', error);
    }
  };

  // Show loading screen while checking authentication
  if (isLoading) {
    return (
      <View style={[styles.container, styles.loadingContainer]}>
        <StatusBar style="auto" />
        {/* You could add a loading spinner here */}
      </View>
    );
  }

  // Show login screen if not authenticated
  if (!isLoggedIn) {
    return (
      <View style={styles.container}>
        <StatusBar style="auto" />
        <LoginScreen onLoginSuccess={handleLoginSuccess} />
      </View>
    );
  }

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
            options={{ title: 'Health Tracker' }}
          >
            {(props) => <DashboardScreen {...props} userInfo={userInfo} onLogout={handleLogout} />}
          </Tab.Screen>
          <Tab.Screen 
            name="Health Form" 
            options={{ title: 'Health Report' }}
          >
            {(props) => <HealthFormScreen {...props} userInfo={userInfo} />}
          </Tab.Screen>
          <Tab.Screen 
            name="Longevity Form" 
            options={{ title: 'Longevity Assessment' }}
          >
            {(props) => <LongevityFormScreen {...props} userInfo={userInfo} />}
          </Tab.Screen>
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
  loadingContainer: {
    justifyContent: 'center',
    alignItems: 'center',
  },
});